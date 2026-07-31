<?php

namespace App\Modules\Report\Console;

use App\Modules\Report\Infrastructure\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class PurgeExpiredReportExportsCommand extends Command
{
    protected $signature = 'app:purge-report-exports';

    protected $description = 'Remove expired private report export files';

    public function handle(): int
    {
        ReportExport::query()->where('expires_at', '<=', now())->chunkById(100, function ($exports): void {
            foreach ($exports as $export) {
                if (is_string($export->path) && $export->path !== '') {
                    Storage::disk('local')->delete($export->path);
                }
                $export->update(['status' => 'expired', 'path' => null]);
            }
        });

        return self::SUCCESS;
    }
}
