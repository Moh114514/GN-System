<?php

namespace App\Modules\Report\Presentation\Http;

use App\Modules\Report\Application\Services\ReportExportManager;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExportController
{
    public function __invoke(Request $request, ReportExport $export, ReportExportManager $exports): StreamedResponse
    {
        abort_unless((int) $export->created_by === (int) $request->user()?->id, 403);
        $exports->assertCurrentAccess($export);
        abort_unless(
            $export->status === 'completed'
            && $export->expires_at->isFuture()
            && is_string($export->path)
            && Storage::disk('local')->exists($export->path),
            404,
        );

        $prefix = $export->kind === 'dashboard' ? 'dashboard' : 'orders';

        return Storage::disk('local')->download($export->path, "{$prefix}-{$export->id}.{$export->format}");
    }
}
