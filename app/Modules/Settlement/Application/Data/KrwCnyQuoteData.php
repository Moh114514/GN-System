<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class KrwCnyQuoteData
{
    public function __construct(
        public bool $available,
        public string $source,
        public ?string $rate = null,
        public ?CarbonImmutable $quotedAt = null,
        public ?string $failureReason = null,
    ) {}
}
