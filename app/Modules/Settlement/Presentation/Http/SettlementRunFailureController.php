<?php

namespace App\Modules\Settlement\Presentation\Http;

use App\Modules\Settlement\Application\Services\SettlementRunFailureReportGenerator;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SettlementRunFailureController
{
    public function download(SettlementRun $run, SettlementRunFailureReportGenerator $generator): BinaryFileResponse
    {
        abort_unless($run->failed_agents > 0 && ! empty($run->errors), 404);
        $path = $generator->generate($run);

        return response()->download(
            Storage::disk('local')->path($path),
            __('settlements.failure_report.filename', ['id' => $run->id]),
        )->deleteFileAfterSend();
    }
}
