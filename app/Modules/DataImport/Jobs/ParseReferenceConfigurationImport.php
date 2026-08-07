<?php

namespace App\Modules\DataImport\Jobs;

use App\Infrastructure\Localization\SupportedLocale;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationImportCommitter;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationImportParser;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ParseReferenceConfigurationImport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $batchId,
        public readonly ?string $locale = null,
    ) {}

    public function handle(
        ReferenceConfigurationImportParser $parser,
        ReferenceConfigurationImportCommitter $committer,
    ): void {
        $previousLocale = app()->getLocale();
        app()->setLocale((SupportedLocale::fromCandidate($this->locale) ?? SupportedLocale::default())->value);
        try {
            $batch = ImportBatch::query()
                ->where('kind', 'reference_configuration')
                ->with('files')
                ->findOrFail($this->batchId);
            $parser->parse($batch);
            $batch->refresh();
            if ($batch->status === ImportBatchStatus::Validated) {
                try {
                    $committer->dryRun($batch);
                } catch (Throwable $exception) {
                    $batch->update([
                        'status' => ImportBatchStatus::Failed,
                    ]);
                    throw $exception;
                }
            }
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
