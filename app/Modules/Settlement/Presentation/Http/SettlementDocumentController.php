<?php

namespace App\Modules\Settlement\Presentation\Http;

use App\Modules\Settlement\Application\Services\SettlementDocumentGenerator;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SettlementDocumentController
{
    public function document(SettlementDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download(
            $document->path,
            "settlement-{$document->settlement_id}.{$document->format}",
        );
    }

    public function archive(string $run, SettlementDocumentGenerator $generator): StreamedResponse
    {
        $path = $generator->archiveRun($run);

        return Storage::disk('local')->download($path, "settlements-{$run}.zip");
    }
}
