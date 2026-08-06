<?php

namespace App\Modules\Agent\Application\Data;

final readonly class ResolvedAgentImportReference
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $legacyCode,
    ) {}
}
