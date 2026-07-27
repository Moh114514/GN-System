<?php

namespace App\Modules\DataImport\Console;

use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use Illuminate\Console\Command;

class PurgeExpiredImportsCommand extends Command
{
    protected $signature = 'app:purge-imports';

    protected $description = '清理超过回滚窗口或失败保留期的导入源文件和原始敏感数据';

    public function handle(EncryptedImportStorage $storage): int
    {
        $completed = ImportBatch::query()
            ->with('files')
            ->where('status', ImportBatchStatus::Completed)
            ->where('rollback_expires_at', '<=', now())
            ->get();

        foreach ($completed as $batch) {
            foreach ($batch->files as $file) {
                if ($file->encrypted_path !== '') {
                    $storage->delete($file->encrypted_path);
                    $file->update(['encrypted_path' => '']);
                }
            }
            $batch->rows()->update(['raw_payload_encrypted' => null]);
            $batch->update(['status' => ImportBatchStatus::Expired]);
        }

        $failedBefore = now()->subDays((int) config('data-import.failed_retention_days', 7));
        $failed = ImportBatch::query()
            ->with('files')
            ->whereIn('status', [ImportBatchStatus::Failed, ImportBatchStatus::NeedsReview])
            ->where('updated_at', '<=', $failedBefore)
            ->get();

        foreach ($failed as $batch) {
            foreach ($batch->files as $file) {
                if ($file->encrypted_path !== '') {
                    $storage->delete($file->encrypted_path);
                }
            }
            $batch->delete();
        }

        $this->info("已清理 {$completed->count()} 个过期批次和 {$failed->count()} 个失败/待处理批次。");

        return self::SUCCESS;
    }
}
