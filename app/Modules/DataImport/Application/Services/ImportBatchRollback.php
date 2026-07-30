<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\Order\Application\Contracts\OrderImportGateway;
use App\Modules\Reminder\Application\Contracts\FollowupImportGateway;
use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ImportBatchRollback
{
    public function __construct(
        private SettlementImportGateway $settlements,
        private FollowupImportGateway $followups,
        private OrderImportGateway $orders,
        private CustomerImportGateway $customers,
        private AgentImportGateway $agents,
        private CatalogImportGateway $catalog,
        private AuditRecorder $audit,
    ) {}

    public function rollback(ImportBatch $batch, int $userId): void
    {
        if (($batch->kind ?? 'historical') !== 'historical' || ! $batch->canRollback()) {
            throw new RuntimeException('该批次不在允许回滚的 24 小时窗口内。');
        }

        DB::transaction(function () use ($batch, $userId): void {
            $batch->refresh();
            if (! $batch->canRollback() || $batch->completed_at === null) {
                throw new RuntimeException('该批次已过回滚窗口或状态已改变。');
            }

            $blockers = [
                ...$this->settlements->rollbackBlockers($batch->id, $batch->completed_at),
                ...$this->followups->rollbackBlockers($batch->id, $batch->completed_at),
                ...$this->orders->rollbackBlockers($batch->id, $batch->completed_at),
                ...$this->customers->rollbackBlockers($batch->id, $batch->completed_at),
                ...$this->agents->rollbackBlockers($batch->id, $batch->completed_at),
                ...$this->catalog->rollbackBlockers($batch->id, $batch->completed_at),
            ];

            if ($blockers !== []) {
                throw new RuntimeException(
                    '导入数据已被后续修改，禁止回滚。阻塞记录：'.implode('、', array_slice($blockers, 0, 50)),
                );
            }

            $this->settlements->deleteImportedByBatch($batch->id);
            $this->followups->deleteImportedByBatch($batch->id);
            $this->orders->deleteImportedByBatch($batch->id);
            $this->customers->deleteImportedByBatch($batch->id);
            $this->agents->deleteImportedByBatch($batch->id);
            $this->catalog->deleteImportedByBatch($batch->id);

            $batch->update([
                'status' => ImportBatchStatus::RolledBack,
                'rolled_back_at' => now(),
                'rolled_back_by' => $userId,
            ]);

            $this->audit->record('回滚历史数据导入', [
                'import_batch_id' => $batch->id,
            ], $userId);
        }, 3);
    }
}
