<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Application\Contracts\OrderLifecycleGateway;
use App\Modules\Order\Application\Data\OrderUpdateData;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseOrderLifecycleGateway implements OrderLifecycleGateway
{
    public function __construct(
        private AgentReferenceReader $agents,
        private CustomerOrderReferenceReader $customers,
        private InstitutionReferenceReader $institutions,
        private DailyCommissionGateway $commissions,
        private TreatmentReminderGateway $reminders,
        private AuditRecorder $audit,
    ) {}

    public function updatePending(OrderUpdateData $data, int $actorId, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($data, $actorId, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($data->orderId);
            if ($order->status !== 'pending') {
                throw new DomainException('只有待完成订单可以编辑。');
            }
            $this->assertEditableReferences($data);

            $before = $order->only([
                'institution_id', 'channel', 'agent_id', 'direct_sales_source_id', 'project_name',
                'amount_krw', 'treatment_project_id', 'treatment_project_snapshot',
                'translator_name', 'translator_language_id', 'translator_language_snapshot', 'notes',
            ]);
            $projectName = trim($data->projectName);
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
                'institution_id' => $data->institutionId,
                'channel' => $data->channel,
                'agent_id' => $data->agentId,
                'direct_sales_source_id' => $data->directSalesSourceId,
                'project_name' => $projectName,
                'amount_krw' => $data->amountKrw,
                'treatment_project_snapshot' => $projectSnapshot,
                'treatment_project_id' => $data->treatmentProjectId,
                'translator_language_id' => $data->translatorLanguageId,
                'translator_language_snapshot' => $languageSnapshot,
                'translator_name' => $data->translatorName,
                'notes' => $data->notes,
            ]);

            $after = $order->only(array_keys($before));
            if ($before !== $after) {
                $this->audit->record(
                    description: '待完成订单已更新',
                    properties: ['before' => $before, 'after' => $after],
                    causerId: $actorId,
                    subject: $order,
                    logName: 'order',
                    event: 'updated',
                    ipAddress: $ipAddress,
                );
            }

            return (int) $order->id;
        });
    }

    public function cancel(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $reason, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'pending') {
                throw new DomainException('只有待完成订单可以取消。');
            }
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_reason' => trim($reason),
            ]);
            $this->audit->record(
                description: '订单已取消',
                properties: ['reason' => trim($reason)],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'cancelled',
                ipAddress: $ipAddress,
            );

            return (int) $order->id;
        });
    }

    public function reopen(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $reason, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'cancelled' || $order->trashed()) {
                throw new DomainException('只有未删除的已取消订单可以重新打开。');
            }
            $order->update([
                'status' => 'pending',
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
            ]);
            $this->audit->record(
                description: '订单已重新打开',
                properties: ['reason' => trim($reason)],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'reopened',
                ipAddress: $ipAddress,
            );

            return (int) $order->id;
        });
    }

    public function rollbackCompleted(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $reason, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'completed') {
                throw new DomainException('只有已完成订单可以受控回退到待完成。');
            }

            $before = $order->only(['status', 'completed_on', 'completed_at', 'completion_precision']);
            $this->commissions->rollbackForOrder($orderId);
            $this->reminders->cancelForOrder($orderId, $actorId, $reason);
            $order->update([
                'status' => 'pending',
                'completed_on' => null,
                'completed_at' => null,
                'completion_precision' => 'date',
            ]);
            $this->audit->record(
                description: '订单已受控回退至待完成',
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
            );

            return (int) $order->id;
        });
    }

    public function softDelete(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $reason, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'cancelled') {
                throw new DomainException('只有已取消订单可以移入回收站。');
            }
            $order->update(['deleted_by' => $actorId, 'deletion_reason' => trim($reason)]);
            $order->delete();
            $this->audit->record(
                description: '订单已移入回收站',
                properties: ['reason' => trim($reason)],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'deleted',
                ipAddress: $ipAddress,
            );

            return (int) $order->id;
        });
    }

    public function restore(int $orderId, int $actorId, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $ipAddress): int {
            $order = Order::withTrashed()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'cancelled' || ! $order->trashed()) {
                throw new DomainException('只有已取消的回收站订单可以恢复。');
            }
            $order->restore();
            $order->update(['deleted_by' => null, 'deletion_reason' => null]);
            $this->audit->record(
                description: '订单已从回收站恢复',
                properties: [],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'restored',
                ipAddress: $ipAddress,
            );

            return (int) $order->id;
        });
    }

    private function assertEditableReferences(OrderUpdateData $data): void
    {
        if ($this->institutions->institutionsByIds([$data->institutionId]) === []) {
            throw new DomainException('所选机构不存在或已停用。');
        }
        if ($data->channel === 'agent') {
            if ($data->agentId === null || $data->directSalesSourceId !== null) {
                throw new DomainException('代理商订单必须且只能选择一个代理商。');
            }
            $agent = $this->agents->agentById($data->agentId);
            if ($agent['cooperation_status'] !== 'active') {
                throw new DomainException('代理商当前不是合作中状态，不能保存订单。');
            }

            return;
        }
        if ($data->channel !== 'direct' || $data->directSalesSourceId === null || $data->agentId !== null) {
            throw new DomainException('直销订单必须且只能选择一个直销来源。');
        }
        $sourceIds = array_column($this->customers->activeDirectSalesSources(), 'id');
        if (! in_array($data->directSalesSourceId, $sourceIds, true)) {
            throw new DomainException('所选直销来源不存在或已停用。');
        }
    }
}
