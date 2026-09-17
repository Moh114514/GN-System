<?php

namespace App\Modules\Settlement\Presentation\Http;

use App\Modules\Settlement\Application\Services\SettlementDocumentGenerator;
use App\Modules\Settlement\Application\Services\SettlementReadScope;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SettlementDocumentController
{
    public function document(SettlementDocument $document, SettlementReadScope $scope): StreamedResponse
    {
        $settlement = Settlement::query()->findOrFail($document->settlement_id);
        $scope->assertVisible($settlement);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download(
            $document->path,
            __('settlements.documents.filename', ['id' => $document->settlement_id, 'format' => $document->format]),
        );
    }

    public function archive(string $run, SettlementDocumentGenerator $generator): StreamedResponse
    {
        abort_unless(request()->user()?->isSuperAdmin(), 403);
        $path = $generator->archiveRun($run);

        return Storage::disk('local')->download($path, __('settlements.documents.archive_filename', ['run' => $run]));
    }
}
