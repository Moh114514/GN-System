<?php

namespace App\Modules\Settlement\Presentation\Http;

use App\Modules\Settlement\Application\Services\BdQuarterlyCommissionDocumentGenerator;
use App\Modules\Settlement\Application\Services\BdQuarterlyCommissionService;
use App\Modules\Settlement\Infrastructure\Models\BdQuarterlyCommission;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BdQuarterlyCommissionDocumentController
{
    public function download(
        BdQuarterlyCommission $period,
        int $bdUserId,
        string $format,
        BdQuarterlyCommissionService $service,
        BdQuarterlyCommissionDocumentGenerator $generator,
    ): Response|StreamedResponse {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        $detail = $service->visibleDetail((int) $period->id, $bdUserId);
        $data = $generator->data($detail, $bdUserId);
        $filename = __('settlements.bd_commission.documents.filename', ['period' => $period->id, 'bd' => $bdUserId, 'format' => $format]);

        if ($format === 'xlsx') {
            return response()->streamDownload(
                static fn () => $generator->xlsx($data),
                $filename,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            );
        }

        return response($generator->pdf($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
        ]);
    }
}
