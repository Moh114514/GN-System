<?php

namespace App\Modules\Order\Application\Data;

final readonly class InstitutionReturnUploadData
{
    public function __construct(
        public int $institutionId,
        public int $customerId,
        public string $originalName,
        public string $extension,
        public ?string $mimeType,
        public string $contents,
        public int $actorId,
        public ?string $ipAddress,
    ) {}
}
