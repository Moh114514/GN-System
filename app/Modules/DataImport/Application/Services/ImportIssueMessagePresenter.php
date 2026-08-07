<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportIssue;
use Illuminate\Support\Facades\Lang;

final class ImportIssueMessagePresenter
{
    public function present(ImportIssue $issue): string
    {
        $key = $issue->message_key ?? "imports.errors.{$issue->code}";
        $parameters = is_array($issue->message_parameters) ? $issue->message_parameters : [];

        if (Lang::has($key)) {
            return (string) __($key, $parameters);
        }

        return (string) __('imports.errors.generic', ['code' => $issue->code]);
    }

    public function presentBatch(ImportBatch $batch): string
    {
        $key = $batch->failure_reason_key ?? 'imports.errors.batch_failure';
        $parameters = is_array($batch->failure_reason_parameters) ? $batch->failure_reason_parameters : [];

        return Lang::has($key)
            ? (string) __($key, $parameters)
            : (string) __('imports.errors.batch_failure');
    }
}
