<?php

namespace App\Modules\DataImport\Infrastructure;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class EncryptedImportStorage
{
    /**
     * @return array{path: string, sha256: string, size: int}
     */
    public function store(string $batchId, UploadedFile $file): array
    {
        $contents = $file->get();
        if ($contents === false) {
            throw new RuntimeException("无法读取上传文件：{$file->getClientOriginalName()}");
        }

        $path = "imports/{$batchId}/".Str::uuid().'.enc';
        Storage::disk('local')->put($path, Crypt::encryptString($contents));

        return [
            'path' => $path,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
        ];
    }

    public function decrypt(string $encryptedPath): string
    {
        $payload = Storage::disk('local')->get($encryptedPath);

        return Crypt::decryptString($payload);
    }

    public function delete(string $encryptedPath): void
    {
        Storage::disk('local')->delete($encryptedPath);
    }
}
