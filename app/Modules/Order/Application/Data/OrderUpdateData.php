<?php

namespace App\Modules\Order\Application\Data;

final readonly class OrderUpdateData
{
    public function __construct(
        public int $orderId,
        public int $institutionId,
        public string $channel,
        public ?int $agentId,
        public ?int $directSalesSourceId,
        public string $projectName,
        public int $amountKrw,
        public ?string $translatorName,
        public ?string $notes,
        public ?int $treatmentProjectId,
        public ?int $translatorLanguageId,
        public ?string $translatorLanguageName,
    ) {}
}
