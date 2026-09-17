<?php

namespace App\Modules\Order\Presentation\Http;

use App\Modules\Order\Application\Services\InstitutionReturnAccess;
use App\Modules\Order\Infrastructure\InstitutionReturnStorage;
use App\Modules\Order\Infrastructure\Models\InstitutionReturnFile;
use Illuminate\Http\Response;

final readonly class InstitutionReturnFileController
{
    public function __construct(
        private InstitutionReturnAccess $access,
        private InstitutionReturnStorage $storage,
    ) {}

    public function download(string $returnFile): Response
    {
        $file = InstitutionReturnFile::query()->findOrFail($returnFile);
        if ($file->customer_id !== null) {
            $this->access->authorizeCustomerDownload((int) $file->customer_id);
        } else {
            abort_unless(auth()->user()?->isSuperAdmin() === true, 404);
        }

        $contents = $this->storage->decrypt((string) $file->encrypted_path);

        return response($contents, 200, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes((string) $file->original_name, '"\\').'"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }
}
