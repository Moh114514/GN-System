<?php

namespace App\Modules\Order\Infrastructure;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class InstitutionReturnStorage
{
    /** @return array{path: string, sha256: string, size: int} */
    public function store(string $returnFileId, string $contents): array
    {
        if ($contents === '') {
            throw new RuntimeException('机构回传文件为空。');
        }

        $path = "institution-returns/{$returnFileId}/".Str::uuid().'.enc';
        Storage::disk('local')->put($path, Crypt::encryptString($contents));

        return [
            'path' => $path,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
        ];
    }

    public function decrypt(string $path): string
    {
        return Crypt::decryptString(Storage::disk('local')->get($path));
    }

    public function delete(string $path): void
    {
        Storage::disk('local')->delete($path);
    }
}
