<?php

namespace App\Modules\Order\Presentation\Http;

use App\Modules\Order\Application\Services\InstitutionReturnAccess;
use App\Modules\Order\Infrastructure\Models\OrderEvidenceFile;
use App\Modules\Order\Infrastructure\OrderEvidenceStorage;
use Illuminate\Http\Response;

final readonly class OrderEvidenceFileController
{
    public function __construct(
        private InstitutionReturnAccess $access,
        private OrderEvidenceStorage $storage,
    ) {}

    public function download(int $evidence): Response
    {
        $file = OrderEvidenceFile::query()->with('order')->findOrFail($evidence);
        $this->access->authorizeCustomerDownload((int) $file->order->customer_id);
        $contents = $this->storage->decrypt((string) $file->encrypted_path);

        return response($contents, 200, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes((string) $file->original_name, '"\\').'"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }
}
