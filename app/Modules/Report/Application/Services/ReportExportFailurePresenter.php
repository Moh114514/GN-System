<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Report\Infrastructure\Models\ReportExport;

final class ReportExportFailurePresenter
{
    public function present(ReportExport $export): ?string
    {
        if ($export->status !== 'failed') {
            return null;
        }

        $key = $export->failure_reason_key;
        if (! in_array($key, [
            'search.page.exports.failure_reasons.too_many_rows',
            'search.page.exports.failure_reasons.generation_failed',
            'search.page.exports.failure_reasons.unexpected',
        ], true)) {
            return __('search.page.exports.failure_reasons.generic');
        }

        return __($key, is_array($export->failure_reason_parameters) ? $export->failure_reason_parameters : []);
    }
}
