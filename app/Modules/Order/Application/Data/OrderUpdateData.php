<?php

namespace App\Modules\Order\Application\Data;

use Carbon\CarbonImmutable;

final readonly class OrderUpdateData
{
    public function __construct(
        public int $orderId,
        public int $institutionId,
        public int $agentId,
        public string $projectName,
        public int $amountKrw,
        public ?string $translatorName,
        public ?string $notes,
        public ?int $treatmentProjectId,
        public ?int $translatorLanguageId,
        public ?string $translatorLanguageName,
        public ?CarbonImmutable $occurredOn = null,
        /** @var array<int, array<string, mixed>> */
        public array $items = [],
        public ?string $reason = null,
        public ?string $expectedUpdatedAt = null,
    ) {}
}
