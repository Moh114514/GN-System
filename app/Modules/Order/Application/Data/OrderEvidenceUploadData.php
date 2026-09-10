<?php

namespace App\Modules\Order\Application\Data;

final readonly class OrderEvidenceUploadData
{
    public function __construct(
        public string $type,
        public string $originalName,
        public ?string $mimeType,
        public string $contents,
    ) {}
}
